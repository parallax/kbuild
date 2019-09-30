# What is Kbuild?

Kbuild is a tool Parallax has created internally to facilitate deploying apps to Kubernetes. As an agency we have 200 or so projects that we've built for customers - these range from a simple website through to complex software.

We used to use shared hosting of some sort (nice shared hosting) but were outgrowing the limitations of it as different projects had different dependencies and it wasn't just a case of "some PHP and some MySQL" anymore. Containers, Docker and Kubernetes make sense for our use case and while they add significant complexity they also make it possible to run varied workloads in a homogenous manner.

## Project Objectives

This is our second go at writing something like this. The first one was very prescriptive and tried to force you down certain paths. Which worked, until what you wanted to do wasn't a good fit anymore.

Kbuild tries to mostly stay out of the way and let you write templated YAML files that are easy to deploy.

## Getting Started

There's a few basics that we believe are important to using Kubernetes in an organisation:

* 